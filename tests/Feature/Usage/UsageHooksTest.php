<?php

namespace Tests\Feature\Usage;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AcademicPeriod;
use App\Models\ClassNotebookEntry;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Intervention;
use App\Models\InterventionType;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Services\Assessment\RecordScores;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * Os ganchos de registo de ações nos controladores (docs/usage-insights.md):
 * cada ação concluída grava UMA linha, só depois de a operação ter sucesso e só
 * se mudou alguma coisa.
 */
class UsageHooksTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase, SetsUsageCoverage;

    protected function setUp(): void
    {
        parent::setUp();

        // O relógio fixa-se ANTES de criar dados, e a cobertura diz desde quando há recolha.
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->bootLessonFixtures();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function eventsOf(UsageEventType $type): int
    {
        return UsageEvent::query()->where('event', $type->value)->count();
    }

    private function soleEventOf(UsageEventType $type): UsageEvent
    {
        return UsageEvent::query()->where('event', $type->value)->sole();
    }

    // --- Aulas e sumários --------------------------------------------------

    private function saveSummary(string $lessonUlid, int $version, string $content, array $extra = []): void
    {
        $this->asTeacher()->put("/lessons/{$lessonUlid}/summary", [
            'summary_version' => $version,
            'content' => $content,
            ...$extra,
        ])->assertRedirect();
    }

    #[Test]
    public function saving_a_summary_records_one_useful_action(): void
    {
        $lesson = $this->makeLesson();

        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Números racionais.');

        $event = $this->soleEventOf(UsageEventType::LessonSummarySaved);
        $this->assertSame(1, $event->item_count);
        $this->assertSame(UsageFeature::Lessons, $event->feature);
        $this->assertTrue($event->event->isUsefulRecord());
        $this->assertSame(1, UsageEvent::query()->count());
    }

    #[Test]
    public function saving_the_same_summary_again_on_another_day_records_nothing_new(): void
    {
        $lesson = $this->makeLesson();
        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Mesmo texto.');

        // Outro dia: se a gravação igual contasse, haveria uma segunda linha
        // (a deduplicação por dia não a esconderia).
        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Mesmo texto.');

        $this->assertSame(1, $this->eventsOf(UsageEventType::LessonSummarySaved));
    }

    #[Test]
    public function changing_the_summary_the_same_day_is_still_one_row(): void
    {
        $lesson = $this->makeLesson();
        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Primeira versão.');
        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Segunda versão, diferente.');

        $this->assertSame(1, $this->eventsOf(UsageEventType::LessonSummarySaved));
    }

    #[Test]
    public function changing_the_summary_on_another_day_is_a_new_action(): void
    {
        $lesson = $this->makeLesson();
        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Primeira versão.');

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->saveSummary($lesson->ulid, $this->summaryVersion($lesson), 'Texto alterado.');

        $this->assertSame(2, $this->eventsOf(UsageEventType::LessonSummarySaved));
    }

    #[Test]
    public function the_content_only_endpoint_records_a_summary_too(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()->patch("/lessons/{$lesson->ulid}/summary/content", [
            'summary_version' => $this->summaryVersion($lesson),
            'content' => 'Só o texto.',
        ])->assertRedirect();

        $this->assertSame(1, $this->eventsOf(UsageEventType::LessonSummarySaved));
    }

    #[Test]
    public function a_validation_error_records_nothing(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'summary_version' => $this->summaryVersion($lesson),
            'content' => '',
        ])->assertSessionHasErrors('content');

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_support_session_records_nothing(): void
    {
        $lesson = $this->makeLesson();
        $class = $this->schoolClass;

        // Durante o acesso técnico a gravação do sumário é recusada...
        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->put("/lessons/{$lesson->ulid}/summary", [
                'summary_version' => $this->summaryVersion($lesson),
                'content' => 'Texto.',
            ])->assertForbidden();

        // ...e mesmo uma ação que o suporte consegue fazer não deixa rasto.
        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->post("/classes/{$class->ulid}/students", ['name' => 'Aluno Suporte'])
            ->assertRedirect();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function marking_a_lesson_as_taught_records_once_and_only_if_it_was_not_taught_already(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::LessonTaught);
        $this->assertSame(1, $event->item_count);

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught");

        $this->assertSame(1, $this->eventsOf(UsageEventType::LessonTaught));
    }

    #[Test]
    public function recording_attendance_for_a_taught_lesson_is_one_useful_action(): void
    {
        $enrollment = $this->enroll('Aluno Um');
        $lesson = $this->makeLesson(['status' => LessonStatus::Taught]);

        $this->asTeacher()
            ->post("/lessons/{$lesson->ulid}/attendance", ['absent' => [$enrollment->student->ulid]])
            ->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::LessonAttendanceRecorded);
        $this->assertSame(1, $event->item_count);
        $this->assertTrue($event->event->isUsefulRecord());
    }

    #[Test]
    public function the_batch_of_taught_lessons_is_one_action_with_n_items(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-05 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-07 09:30:00']);

        $this->asTeacher()->post('/lessons/batch-taught', [
            'mode' => 'range',
            'from' => '2026-10-05',
            'to' => '2026-10-07',
        ])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::LessonTaught);
        $this->assertSame(3, $event->item_count);
    }

    // --- Turmas e alunos ---------------------------------------------------

    #[Test]
    public function creating_a_class_and_adding_a_student_are_recorded(): void
    {
        $this->asTeacher()->post('/classes', [
            'label' => '9.º C',
            'academic_year_id' => $this->schoolClass->academic_year_id,
            'subject_id' => $this->schoolClass->subject_id,
        ])->assertRedirect();

        $this->assertSame(1, $this->eventsOf(UsageEventType::ClassCreated));

        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/students", ['name' => 'Nome Inventado'])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::StudentsAdded);
        $this->assertSame(1, $event->item_count);
        $this->assertSame(UsageFeature::Classes, $event->feature);
    }

    #[Test]
    public function a_roster_import_is_one_action_with_the_number_of_students_created(): void
    {
        Storage::fake('local');

        $this->asTeacher()->post('/classes', [
            'label' => '9.º D',
            'academic_year_id' => $this->schoolClass->academic_year_id,
            'subject_id' => $this->schoolClass->subject_id,
        ])->assertRedirect();

        $class = $this->inTenant($this->organization, fn () => SchoolClass::query()->where('label', '9.º D')->firstOrFail());

        $rows = array_map(fn (int $number): array => [
            'name' => "Aluno Inventado {$number}",
            'class_number' => $number,
            'birth_date' => null,
            'situation_code' => 'X',
            'note' => null,
            'process_number' => null,
            'photo_index' => null,
            'photo_extension' => null,
            'include' => true,
        ], [1, 2, 3]);

        $this->asTeacher()->post("/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm', ['rows' => $rows])
            ->assertRedirect("/classes/{$class->ulid}");

        // Uma ação com 3 elementos, nunca 3 ações.
        $event = $this->soleEventOf(UsageEventType::StudentsImported);
        $this->assertSame(3, $event->item_count);
        $this->assertSame(UsageFeature::ImportExport, $event->feature);
        $this->assertSame(0, $this->eventsOf(UsageEventType::StudentsAdded));
    }

    #[Test]
    public function the_first_students_added_milestone_is_marked_by_the_aggregation(): void
    {
        Storage::fake('local');

        $this->asTeacher()->post('/classes', [
            'label' => '9.º E',
            'academic_year_id' => $this->schoolClass->academic_year_id,
            'subject_id' => $this->schoolClass->subject_id,
        ])->assertRedirect();
        $class = $this->inTenant($this->organization, fn () => SchoolClass::query()->where('label', '9.º E')->firstOrFail());

        $this->asTeacher()->post("/classes/{$class->ulid}/roster-imports/".Str::uuid().'/confirm', ['rows' => [[
            'name' => 'Aluna Inventada', 'class_number' => 1, 'birth_date' => null, 'situation_code' => 'X',
            'note' => null, 'process_number' => null, 'photo_index' => null, 'photo_extension' => null, 'include' => true,
        ]]])->assertRedirect();

        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));

        $state = UsageUserState::query()->where('user_id', $this->teacher->id)->sole();
        $this->assertSame('2026-10-12', $state->first_class_created_on?->toDateString());
        $this->assertSame('2026-10-12', $state->first_students_added_on?->toDateString());
    }

    // --- Avaliação ---------------------------------------------------------

    /**
     * @return array{instrument: Instrument, items: list<InstrumentItem>, enrollments: list<Enrollment>}
     */
    private function gradingScenario(int $students = 2): array
    {
        return $this->inTenant($this->organization, function () use ($students): array {
            $period = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
            ]);
            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $this->schoolClass->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
            ]);
            $items = [InstrumentItem::factory()->recycle($this->organization)->create(['instrument_id' => $instrument->id])];
            $enrollments = [];

            for ($index = 1; $index <= $students; $index++) {
                $enrollments[] = $this->enroll("Aluno Nota {$index}");
            }

            return ['instrument' => $instrument, 'items' => $items, 'enrollments' => $enrollments];
        });
    }

    /**
     * @param  list<Enrollment>  $enrollments
     * @return list<array<string, mixed>>
     */
    private function cellsFor(InstrumentItem $item, array $enrollments, float $points, int $lockVersion): array
    {
        return array_map(fn (Enrollment $enrollment): array => [
            'enrollment_id' => $enrollment->id,
            'instrument_item_id' => $item->id,
            'result_state' => 'assessed',
            'lock_version' => $lockVersion,
            'points_earned' => $points,
        ], $enrollments);
    }

    #[Test]
    public function recording_scores_counts_the_cells_that_changed_and_regravar_the_same_scores_records_nothing(): void
    {
        ['instrument' => $instrument, 'items' => [$item], 'enrollments' => $enrollments] = $this->gradingScenario(2);

        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => $this->cellsFor($item, $enrollments, 5, 0),
        ])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::ScoresRecorded);
        $this->assertSame(2, $event->item_count);

        // As mesmas notas, noutro dia: a linha é escrita (a versão sobe) mas
        // nenhuma célula mudou, logo não há ação nova.
        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => $this->cellsFor($item, $enrollments, 5, 1),
        ])->assertRedirect();

        $this->assertSame(1, $this->eventsOf(UsageEventType::ScoresRecorded));

        // Uma só célula alterada: 1 elemento.
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [
                ...$this->cellsFor($item, [$enrollments[0]], 6, 2),
                ...$this->cellsFor($item, [$enrollments[1]], 5, 2),
            ],
        ])->assertRedirect();

        $events = UsageEvent::query()->where('event', 'scores.recorded')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(1, $events[1]->item_count);
    }

    #[Test]
    public function the_service_result_exposes_the_number_of_changed_cells(): void
    {
        ['instrument' => $instrument, 'items' => [$item], 'enrollments' => $enrollments] = $this->gradingScenario(2);

        $this->inTenant($this->organization, function () use ($instrument, $item, $enrollments): void {
            $recorder = app(RecordScores::class);

            $first = $recorder->save($instrument, $this->cellsFor($item, $enrollments, 7.5, 0), $this->teacher);
            $this->assertSame(2, $first->written);
            $this->assertSame(2, $first->changed);

            $same = $recorder->save($instrument->refresh(), $this->cellsFor($item, $enrollments, 7.5, 1), $this->teacher);
            $this->assertSame(2, $same->written);
            $this->assertSame(0, $same->changed);

            $one = $recorder->save($instrument->refresh(), [
                ...$this->cellsFor($item, [$enrollments[0]], 8, 2),
                ...$this->cellsFor($item, [$enrollments[1]], 7.5, 2),
            ], $this->teacher);
            $this->assertSame(2, $one->written);
            $this->assertSame(1, $one->changed);

            $this->assertSame(2, StudentItemScore::query()->count());
        });
    }

    // --- Registos ----------------------------------------------------------

    #[Test]
    public function a_record_for_several_students_is_one_action_with_n_items(): void
    {
        $enrollments = [$this->enroll('Aluno A'), $this->enroll('Aluno B'), $this->enroll('Aluno C')];

        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/records", [
            'kind' => 'participation',
            'disciplinary_severity' => null,
            'homework_status' => null,
            'participation_level' => 'positive',
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => 'Participou.',
            'occurred_at' => '2026-10-12',
            'enrollment_ids' => array_map(fn (Enrollment $enrollment): int => $enrollment->id, $enrollments),
            'domain_id' => null,
        ])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::RecordCreated);
        $this->assertSame(3, $event->item_count);
        $this->assertSame(UsageFeature::Records, $event->feature);
    }

    #[Test]
    public function a_homework_batch_is_one_action_with_the_students_touched(): void
    {
        $enrollments = [$this->enroll('Aluno TPC 1'), $this->enroll('Aluno TPC 2')];

        $this->asTeacher()->putJson("/classes/{$this->schoolClass->ulid}/records/homework-batch", [
            'occurred_at' => '2026-10-12',
            'rows' => array_map(fn (Enrollment $enrollment): array => [
                'enrollment_id' => $enrollment->id,
                'homework_status' => 'done',
                'description' => null,
            ], $enrollments),
        ])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::HomeworkBatchSaved);
        $this->assertSame(2, $event->item_count);
        $this->assertSame(UsageFeature::Records, $event->feature);
    }

    // --- Caracterização pedagógica -----------------------------------------

    #[Test]
    public function an_unchanged_characterisation_save_records_nothing(): void
    {
        $url = "/classes/{$this->schoolClass->ulid}/characterisation";

        $this->asTeacher()->put($url, ['summary' => 'Turma participativa.'])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::CharacterisationUpdated);
        $this->assertSame(1, $event->item_count);
        $this->assertSame(UsageFeature::Characterisation, $event->feature);

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->put($url, ['summary' => 'Turma participativa.'])->assertRedirect();

        $this->assertSame(1, $this->eventsOf(UsageEventType::CharacterisationUpdated));
    }

    // --- Estratégias e medidas ---------------------------------------------

    #[Test]
    public function an_intervention_with_two_types_is_one_action_with_two_items_and_a_followup_is_another(): void
    {
        $enrollment = $this->enroll('Aluno Medida');

        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_types' => [
                InterventionType::PedagogicalDifferentiation->value,
                InterventionType::PsychopedagogicalSupport->value,
            ],
            'domain_relation' => 'none',
            'description' => null,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        $event = $this->soleEventOf(UsageEventType::InterventionCreated);
        $this->assertSame(2, $event->item_count);
        $this->assertSame(UsageFeature::Interventions, $event->feature);

        $interventionUlid = $this->inTenant($this->organization, fn () => Intervention::query()->firstOrFail()->ulid);

        $this->asTeacher()->postJson("/interventions/{$interventionUlid}/reviews", [
            'reviewed_on' => '2026-10-12',
            'effectiveness' => null,
            'notes' => null,
        ])->assertRedirect();

        $this->assertSame(1, $this->eventsOf(UsageEventType::InterventionFollowupAdded));
    }

    // --- Caderno da turma --------------------------------------------------

    #[Test]
    public function notebook_entries_record_creation_and_only_real_edits(): void
    {
        $url = "/classes/{$this->schoolClass->ulid}/notebook";

        $this->asTeacher()->post($url, ['title' => 'Título', 'body' => 'Corpo.'])->assertRedirect();

        $this->assertSame(1, $this->eventsOf(UsageEventType::NotebookEntryCreated));
        $this->assertSame(UsageFeature::ClassNotebook, $this->soleEventOf(UsageEventType::NotebookEntryCreated)->feature);

        $entryUlid = $this->inTenant($this->organization, fn () => ClassNotebookEntry::query()->firstOrFail()->ulid);

        // Editar sem alterar nada: nenhum evento.
        $this->asTeacher()->put("{$url}/{$entryUlid}", ['title' => 'Título', 'body' => 'Corpo.', 'lock_version' => 0])->assertRedirect();
        $this->assertSame(0, $this->eventsOf(UsageEventType::NotebookEntryUpdated));

        // Editar com alteração: um evento.
        $this->asTeacher()->put("{$url}/{$entryUlid}", ['title' => 'Título', 'body' => 'Corpo alterado.', 'lock_version' => 0])->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::NotebookEntryUpdated));
    }

    // --- Consulta real ------------------------------------------------------

    private function asInertiaVisitor(): self
    {
        return $this->asTeacher()->withHeaders([
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ]);
    }

    #[Test]
    public function a_real_inertia_visit_to_lessons_records_a_view_but_a_partial_reload_does_not(): void
    {
        $this->asInertiaVisitor()->get('/lessons', [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'lessons/Index',
            'X-Inertia-Partial-Data' => 'lessons',
        ])->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());

        $this->asInertiaVisitor()->get('/lessons', ['X-Inertia' => 'true'])->assertOk();

        $view = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::FeatureViewed, $view->event);
        $this->assertSame(UsageFeature::Lessons, $view->feature);
        $this->assertNull($view->item_count);
    }
}
