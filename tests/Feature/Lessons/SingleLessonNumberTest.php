<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.172.0 (ADR-0017) — UMA AULA, UM NÚMERO.
 *
 * Até à 0.171.0 o professor podia marcar «esta aula corresponde a mais do que
 * uma lição» e indicar um fim (`lesson_number_end`, «12–13»); a derivação fazia
 * a aula seguinte começar no FIM + 1, e quem estava na lição 12 e marcava a
 * caixa via a ocorrência seguinte sair 14 em vez de 13. Agora uma ocorrência
 * corresponde a um só número, e o conteúdo repetido reutiliza-se com «Basear
 * na aula anterior» (que copia texto, nunca números).
 *
 * `lesson_number_end` fica na base de dados como LEGADO: linhas antigas
 * continuam a ler-se «Lições 12–13», nunca se reescrevem nem renumeram, mas o
 * fim já não ocupa números. Os pedidos só o aceitam vazio (é o que um ecrã
 * antigo envia); qualquer valor é recusado.
 *
 * Os casos A–H vêm do pedido de produto. Calendário de 2026: as quintas-feiras
 * (tempo do horário, 09:30) de outubro são 01, 08, 15, 22 e 29; as segundas 05,
 * 12 e 19; as terças 06 e 13. Cada aula nasce pela via normal (materialização)
 * e as linhas LEGADAS escrevem-se direto na base de dados, como a 0.171.0 as
 * deixava.
 */
class SingleLessonNumberTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RELÓGIO FIXO ANTES de criar qualquer dado (a numeração liga T1/T2 a
        // partir de `slot.created_at`).
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        $this->makeSlot();
    }

    // A) ---------------------------------------------------------------------

    #[Test]
    public function after_lesson_twelve_the_next_occurrence_is_thirteen_taught_or_manual(): void
    {
        // Anterior lecionada 12.
        $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 12]);
        $this->weeks('2026-10-08');

        $next = $this->on('2026-10-08');
        $this->assertSame(13, $next->lesson_number);
        $this->asTeacher()->get("/lessons/{$next->ulid}")
            ->assertInertia(fn ($page) => $page->where('lesson.lesson_number', 13)->where('lesson.lesson_number_end', null)->etc());
    }

    #[Test]
    public function after_a_manual_lesson_twelve_the_next_occurrence_is_thirteen(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 12])->assertSessionHasNoErrors();

        $this->assertSame([12, null, true], $this->row('2026-10-08'));
        $this->assertSame(13, $this->on('2026-10-15')->lesson_number);
    }

    // B) ---------------------------------------------------------------------

    #[Test]
    public function a_legacy_range_twelve_to_thirteen_makes_the_next_lesson_thirteen_not_fourteen_and_is_never_rewritten(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $this->legacy('2026-10-08', 12, 13);
        $this->resequence();

        $this->assertSame(['2026-10-08' => '12–13', '2026-10-15' => '13'], $this->numbers());
        $this->assertSame([12, 13, true], $this->row('2026-10-08'));

        // Marcar a 13 como lecionada e materializar a seguinte: 14.
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($this->on('2026-10-15'), $this->teacher, consolidateAttendance: false));
        $this->weeks('2026-10-22');

        $this->assertSame(['2026-10-08' => '12–13', '2026-10-15' => '13', '2026-10-22' => '14'], $this->numbers());
        $this->assertSame([12, 13, true], $this->row('2026-10-08'));
    }

    #[Test]
    public function a_taught_legacy_end_does_not_reserve_the_following_number_either(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 12, 'lesson_number_end' => 13]);
        $this->weeks('2026-10-08', '2026-10-15');

        $this->assertSame(['2026-10-01' => '12–13', '2026-10-08' => '13', '2026-10-15' => '14'], $this->numbers());
        // A lecionada não foi escrita.
        $this->assertSame([12, 13, false], $this->row('2026-10-01'));
    }

    // C) ---------------------------------------------------------------------

    #[Test]
    public function the_teacher_can_override_the_suggestion_and_it_survives_a_reload(): void
    {
        $taught = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 12]);
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $this->assertSame([12, 13, 14, 15], $this->plainNumbers());

        $target = $this->on('2026-10-08');
        $this->save($target, ['lesson_number' => 20], 'Texto da aula.')->assertSessionHasNoErrors();

        $this->asTeacher()->get("/lessons/{$target->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('lesson.lesson_number', 20)
                ->where('lesson.lesson_number_end', null)
                ->where('lesson.lesson_number_manual', true)
                ->etc());

        // A lecionada está intacta, e as abertas seguintes continuam a partir da 20.
        $this->assertSame([12, null, false], $this->row('2026-10-01'));
        $this->inTenant($this->organization, fn () => $this->assertSame(12, $taught->refresh()->lesson_number));
        $this->assertSame([12, 20, 21, 22], $this->plainNumbers());

        // Só um registo foi tocado, e nenhuma outra aula ganhou sumário.
        $this->inTenant($this->organization, function (): void {
            $this->assertSame(1, AuditEvent::query()->where('event', 'lesson.number_set')->count());
            $this->assertSame(1, LessonSummary::query()->count());
        });
    }

    // D) ---------------------------------------------------------------------

    #[Test]
    public function basing_on_the_previous_summary_copies_text_and_never_numbers_or_identity(): void
    {
        $previous = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 12]);
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create([
            'lesson_id' => $previous->id, 'content' => 'Funções afins.', 'resources' => 'Manual p. 12', 'homework' => 'Ficha 3',
        ]));
        $this->weeks('2026-10-08', '2026-10-15');
        $current = $this->on('2026-10-08');
        $before = $this->row('2026-10-08');
        $this->assertSame(13, $before[0]);

        $response = $this->asTeacher()->getJson("/lessons/{$current->ulid}/previous-summary")
            ->assertOk()
            ->assertJsonPath('content', 'Funções afins.')
            ->assertJsonPath('resources', 'Manual p. 12')
            ->assertJsonPath('homework', 'Ficha 3');
        foreach (['lesson_number', 'lesson_number_end', 'ulid', 'id', 'status', 'outcome'] as $key) {
            $response->assertJsonMissingPath($key);
        }

        // O ecrã grava o texto copiado SEM chaves de número.
        $this->save($current, [], 'Funções afins.')->assertSessionHasNoErrors();

        $fresh = $this->on('2026-10-08');
        // Mesma aula (ulid e hora); ter agora sumário só a passa de «por preparar» a «preparada».
        $this->assertSame([$current->ulid, $current->starts_at->toDateTimeString()], [$fresh->ulid, $fresh->starts_at->toDateTimeString()]);
        $this->assertSame(LessonStatus::Preparation, $current->status);
        $this->assertSame(LessonStatus::Prepared, $fresh->status);
        $this->assertNull($fresh->outcome);
        $this->assertSame($before, $this->row('2026-10-08'));
        $this->assertSame(13, $fresh->lesson_number);

        // A anterior continua 12, com o seu texto, e a seguinte continua 14.
        $this->assertSame(12, $this->on('2026-10-01')->lesson_number);
        $this->inTenant($this->organization, fn () => $this->assertSame('Funções afins.', LessonSummary::query()->where('lesson_id', $previous->id)->value('content')));
        $this->assertSame(14, $this->on('2026-10-15')->lesson_number);
    }

    // F) ---------------------------------------------------------------------

    #[Test]
    public function the_new_payload_carries_only_the_number(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $first = $this->on('2026-10-08');

        $this->save($first, ['lesson_number' => 20])->assertSessionHasNoErrors();
        $this->assertSame([20, null, true], $this->row('2026-10-08'));

        $this->asTeacher()->patch("/lessons/{$this->on('2026-10-15')->ulid}/summary/content", [
            'content' => 'Do cartão.', 'summary_version' => $this->summaryVersion($this->on('2026-10-15')), 'lesson_number' => 30,
        ])->assertSessionHasNoErrors();
        $this->assertSame([30, null, true], $this->row('2026-10-15'));

        $this->asTeacher()->postJson("/lessons/{$first->ulid}/number/preview", ['mode' => 'manual', 'start' => 21])
            ->assertOk()
            ->assertJsonPath('result.start', 21)
            ->assertJsonPath('result.end', null);
    }

    // G) ---------------------------------------------------------------------

    #[Test]
    public function a_legacy_payload_with_an_end_is_refused_everywhere_and_nothing_is_written(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $lesson = $this->on('2026-10-08');
        $before = $this->snapshot();

        $this->save($lesson, ['lesson_number' => 12, 'lesson_number_end' => 13], 'Texto que não pode ficar.')
            ->assertSessionHasErrors('lesson_number_end');

        $this->asTeacher()->patch("/lessons/{$lesson->ulid}/summary/content", [
            'content' => 'Texto que não pode ficar.', 'summary_version' => $this->summaryVersion($lesson),
            'lesson_number' => 12, 'lesson_number_end' => 13,
        ])->assertSessionHasErrors('lesson_number_end');

        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 12, 'end' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');

        $this->assertSame($before, $this->snapshot());

        // Com o fim vazio (um ecrã antigo): aceite, e é uma só lição.
        $this->save($lesson, ['lesson_number' => 12, 'lesson_number_end' => null])->assertSessionHasNoErrors();
        $this->assertSame([12, null, true], $this->row('2026-10-08'));
        $this->asTeacher()->patch("/lessons/{$lesson->ulid}/summary/content", [
            'content' => 'Do cartão.', 'summary_version' => $this->summaryVersion($lesson),
            'lesson_number' => 14, 'lesson_number_end' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame([14, null, true], $this->row('2026-10-08'));
    }

    // H) ---------------------------------------------------------------------

    #[Test]
    public function there_is_no_retroactive_renumbering_of_recorded_lessons(): void
    {
        $this->makeLesson(['starts_at' => '2026-09-17 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 10]);
        $this->makeLesson(['starts_at' => '2026-09-24 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 11, 'lesson_number_end' => 12, 'lesson_number_manual' => true]);
        // Numerada pela regra antiga (depois do fim 12).
        $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'status' => LessonStatus::Taught, 'lesson_number' => 13]);

        $recorded = fn (): array => $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('status', LessonStatus::Taught->value)->orderBy('starts_at')
            ->get(['id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual'])->toArray());
        $before = $recorded();

        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $this->assertSame($before, $recorded());
        $this->assertSame([14, 15, 16], $this->openNumbers());

        // Inserir e eliminar uma aula aberta.
        $this->asTeacher()->post('/lessons/insert', ['class' => $this->schoolClass->ulid, 'insert_at' => '2026-10-15'])->assertSessionHasNoErrors();
        $last = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->where('status', '!=', LessonStatus::Taught->value)->orderByDesc('starts_at')->firstOrFail());
        $this->asTeacher()->delete("/lessons/{$last->ulid}")->assertSessionHasNoErrors();

        $this->assertSame($before, $recorded());
        $this->assertSame(14, $this->openNumbers()[0]);

        // Marcar uma aberta como lecionada: as antigas ficam iguais; a próxima livre é a seguinte.
        $firstOpen = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->where('status', '!=', LessonStatus::Taught->value)->orderBy('starts_at')->firstOrFail());
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($firstOpen, $this->teacher, consolidateAttendance: false));

        $afterMark = array_slice($recorded(), 0, 3);
        $this->assertSame($before, $afterMark);
        $this->assertSame(15, $this->openNumbers()[0]);
    }

    // «Não houve aula» -------------------------------------------------------

    #[Test]
    public function a_teacher_absence_on_a_legacy_range_passes_only_the_number_on(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $this->legacy('2026-10-08', 12, 13);
        $this->resequence();
        $this->assertSame(['2026-10-08' => '12–13', '2026-10-15' => '13', '2026-10-22' => '14'], $this->numbers());

        $this->asTeacher()
            ->post("/lessons/{$this->on('2026-10-08')->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        // A ausente fica sem número; o 12 passa à seguinte como manual e SEM fim; a outra é a 13.
        $this->assertSame([null, null, false], $this->row('2026-10-08'));
        $this->assertSame([12, null, true], $this->row('2026-10-15'));
        $this->assertSame(13, $this->on('2026-10-22')->lesson_number);
        $this->assertNull($this->on('2026-10-22')->lesson_number_end);
    }

    #[Test]
    public function a_class_activity_keeps_numbering_as_a_recorded_lesson(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();
        $this->assertSame([11, 12, 13], $this->plainNumbers());

        $this->asTeacher()
            ->post("/lessons/{$this->on('2026-10-15')->ulid}/outcome", ['outcome' => 'class_external_activity', 'note' => 'Visita de estudo'])
            ->assertSessionHasNoErrors();

        // Numera como lecionada: mantém o 12, e uma semana nova continua em 14.
        $this->assertSame(12, $this->on('2026-10-15')->lesson_number);
        $this->weeks('2026-10-29');
        $this->assertSame([11, 12, 13, 14], $this->plainNumbers());
        $this->assertSame(12, $this->on('2026-10-15')->lesson_number);
    }

    #[Test]
    public function a_second_group_lesson_joining_a_legacy_unit_gets_the_number_without_the_end(): void
    {
        [$t1Slot, $t2Slot] = $this->splitSlots();

        $t1 = $this->groupLesson($t1Slot, '2026-10-05');
        $this->resequence();
        DB::table('lessons')->where('id', $t1->id)->update(['lesson_number' => 12, 'lesson_number_end' => 13, 'lesson_number_manual' => true]);

        // O T2 nasce DEPOIS e junta-se à unidade legada.
        $t2 = $this->groupLesson($t2Slot, '2026-10-06');
        $this->resequence();

        $this->inTenant($this->organization, function () use ($t1, $t2): void {
            $t1->refresh();
            $t2->refresh();
            $this->assertSame($t1->lesson_unit_key, $t2->lesson_unit_key);
            // A legada fica como estava; o recém-chegado não herda o fim.
            $this->assertSame([12, 13, true], [$t1->lesson_number, $t1->lesson_number_end, $t1->lesson_number_manual]);
            $this->assertSame([12, null, true], [$t2->lesson_number, $t2->lesson_number_end, $t2->lesson_number_manual]);
        });
    }

    // Isolamento -------------------------------------------------------------

    #[Test]
    public function a_teacher_of_another_organization_can_neither_preview_nor_save_the_number(): void
    {
        $this->weeks('2026-10-08');
        $lesson = $this->on('2026-10-08');
        $before = $this->snapshot();

        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $this->inTenant($strangerOrganization, function () use ($stranger, $strangerOrganization): void {
            $class = SchoolClass::factory()->recycle($strangerOrganization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($strangerOrganization)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
            $class->teachers()->attach($stranger, ['role' => 'owner']);
        });
        $asStranger = $this->actingAs($stranger)->withSession(['organization_id' => $strangerOrganization->id]);

        $this->assertContains($asStranger->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 20])->status(), [403, 404]);
        $this->assertContains($asStranger->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'x', 'summary_version' => $this->summaryVersion($lesson), 'lesson_number' => 20,
        ])->status(), [403, 404]);

        $this->assertSame($before, $this->snapshot());
    }

    // ------------------------------------------------------------------------

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
    private function save(Lesson $lesson, array $extra = [], string $content = 'Texto do sumário.'): TestResponse
    {
        return $this->asTeacher()->from('/lessons')->put("/lessons/{$lesson->ulid}/summary", [
            'content' => $content,
            'summary_version' => $this->summaryVersion($lesson),
        ] + $extra);
    }

    /** Uma linha como a 0.171.0 a deixava: número manual com fim. */
    private function legacy(string $date, int $start, int $end): void
    {
        DB::table('lessons')->where('id', $this->on($date)->id)->update([
            'lesson_number' => $start, 'lesson_number_end' => $end, 'lesson_number_manual' => true,
        ]);
    }

    /** @return array{0: int|null, 1: int|null, 2: bool} */
    private function row(string $date): array
    {
        $lesson = $this->on($date);

        return [$lesson->lesson_number, $lesson->lesson_number_end, (bool) $lesson->lesson_number_manual];
    }

    /** @return array<string, string|null> */
    private function numbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->get()
            ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->starts_at->format('Y-m-d') => $lesson->lessonNumberLabel()])
            ->all());
    }

    /**
     * @param  list<string>|null  $dates
     * @return list<int|null>
     */
    private function plainNumbers(?array $dates = null): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)
            ->when($dates !== null, fn ($query) => $query->where(fn ($inner) => collect($dates)->each(fn (string $date) => $inner->orWhereDate('starts_at', $date))))
            ->orderBy('starts_at')->get()
            ->map(fn (Lesson $lesson): ?int => $lesson->lesson_number)->all());
    }

    /** @return list<int|null> */
    private function openNumbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('status', '!=', LessonStatus::Taught->value)->orderBy('starts_at')->get()
            ->map(fn (Lesson $lesson): ?int => $lesson->lesson_number)->all());
    }

    /** O estado inteiro (aulas, sumários, auditoria) — para provar que nada foi escrito. */
    private function snapshot(): array
    {
        return [
            'audit' => DB::table('audit_events')->count(),
            'summaries' => DB::table('lesson_summaries')->count(),
            'lessons' => json_decode((string) DB::table('lessons')->orderBy('id')->get(['id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual', 'summary_version', 'updated_at'])->toJson(), true),
        ];
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
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
}
