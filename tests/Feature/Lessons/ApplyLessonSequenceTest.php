<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplyLessonSequenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $teacher;

    private Subject $subject;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
        $this->subject = $this->subjectFor();
        $this->year = $this->academicYearFor();

        Carbon::setTestNow('2026-10-01 08:00:00');
    }

    #[Test]
    public function applying_with_all_options_creates_independent_summaries_with_provenance_and_skips_closed_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $taughtLesson = $this->lessonFor($schoolClass, '2026-10-05 09:00:00', LessonStatus::Taught);
        $lessonOne = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $lessonTwo = $this->lessonFor($schoolClass, '2026-10-07 09:00:00');

        $sequence = $this->sequenceFor([
            ['summary' => 'Sumário 1.', 'private_notes' => 'Nota 1.', 'resources' => 'Recurso 1.', 'homework' => 'TPC 1.'],
            ['summary' => 'Sumário 2.', 'private_notes' => 'Nota 2.', 'resources' => 'Recurso 2.', 'homework' => 'TPC 2.'],
        ]);

        $response = $this->applyWithPreview($sequence, $this->applyPayload($schoolClass));
        $response->assertRedirect();

        $response->assertSessionHas(
            'inertia.flash_data',
            fn ($flash) => ($flash['toast'] ?? null) === ['type' => 'success', 'message' => 'Sequência aplicada a partir de 01/10: 2 aulas preparadas.'],
        );

        $this->inTenant($this->organization, function () use ($schoolClass, $sequence, $taughtLesson, $lessonOne, $lessonTwo): void {
            $this->assertSame(LessonStatus::Taught, $taughtLesson->refresh()->status);
            $this->assertNull($taughtLesson->summary()->first());

            $summaryOne = $lessonOne->refresh()->summary()->sole();
            $this->assertSame('Sumário 1.', $summaryOne->content);
            $this->assertSame('Nota 1.', $summaryOne->private_notes);
            $this->assertSame('Recurso 1.', $summaryOne->resources);
            $this->assertSame('TPC 1.', $summaryOne->homework);
            $this->assertSame(LessonStatus::Prepared, $lessonOne->status);
            $this->assertSame($sequence->id, $summaryOne->lesson_sequence_id);
            $this->assertSame($sequence->items()->orderBy('position')->first()->id, $summaryOne->lesson_sequence_item_id);
            $this->assertSame($summaryOne->contentFingerprint(), $summaryOne->sequence_content_hash);

            $summaryTwo = $lessonTwo->refresh()->summary()->sole();
            $this->assertSame('Sumário 2.', $summaryTwo->content);
            $this->assertSame(LessonStatus::Prepared, $lessonTwo->status);

            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSame($schoolClass->id, $event->properties['class_id']);
            $this->assertSame(2, $event->properties['items_applied']);
            $this->assertSame(0, $event->properties['items_skipped']);
            $this->assertSame('2026-10-01', $event->properties['from']);
            $this->assertSame(1, $event->properties['counts']['closed']);
            $this->assertSame(2, $event->properties['counts']['fill']);
            // A coluna JSON do MySQL reordena as chaves: comparar sem depender da ordem.
            $this->assertSameJsonPayload(['summary' => true, 'resources' => true, 'homework' => true, 'private_notes' => true], $event->properties['copy_options']);
        });
    }

    /**
     * Mudou de propósito: antes, uma aula com conteúdo recebia só os campos em
     * branco e CONSUMIA o elemento. Agora uma aula preparada é preservada
     * intacta (seja qual for o campo escrito pelo professor) e o horário é
     * saltado sem consumir o elemento.
     *
     * @param  non-empty-string  $field
     */
    #[Test]
    #[DataProvider('teacherFields')]
    public function a_prepared_lesson_is_preserved_intact_and_does_not_consume_an_item(string $field): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $prepared = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $free = $this->lessonFor($schoolClass, '2026-10-07 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create(
            array_merge(['lesson_id' => $prepared->id, 'content' => ''], [$field => 'Texto do professor.']),
        ));
        $before = $this->inTenant($this->organization, fn () => $prepared->summary()->sole()->only(['content', 'resources', 'homework', 'private_notes']));

        $sequence = $this->sequenceFor([['summary' => 'Item único.']]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($prepared, $free, $before): void {
            $this->assertSame($before, $prepared->summary()->sole()->only(['content', 'resources', 'homework', 'private_notes']));
            $this->assertNull($prepared->summary()->sole()->lesson_sequence_id);
            $this->assertSame('Item único.', $free->summary()->sole()->content);
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function teacherFields(): array
    {
        return [
            'content' => ['content'],
            'resources' => ['resources'],
            'homework' => ['homework'],
            'private_notes' => ['private_notes'],
        ];
    }

    #[Test]
    public function a_lesson_with_no_existing_summary_skips_content_when_the_summary_option_is_off_but_still_gets_other_selected_fields(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([[
            'summary' => 'Nunca deve aparecer — a opção Sumário está desligada.',
            'resources' => 'Recurso do item.',
        ]]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => true,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('', $summary->content);
            $this->assertSame('Recurso do item.', $summary->resources);
            $this->assertNull($summary->homework);
            $this->assertNull($summary->private_notes);
        });
    }

    #[Test]
    public function an_item_with_nothing_to_copy_for_the_chosen_options_consumes_the_lesson_without_creating_a_row(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Texto que nunca deve ser aplicado.']]);

        // Só «Recursos» está selecionado e o elemento não tem recursos.
        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass, [
            'summary' => false,
            'resources' => true,
            'homework' => false,
            'private_notes' => false,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('lesson_summaries', ['lesson_id' => $lesson->id]);
        $this->assertDatabaseCount('lesson_summaries', 0);

        $this->inTenant($this->organization, function () use ($lesson): void {
            $event = AuditEvent::query()->where('event', 'lesson_sequence.applied')->sole();
            $this->assertSame(0, $event->properties['items_applied']);
            $this->assertSame(0, $event->properties['items_skipped']);
            $this->assertSame(1, $event->properties['counts']['nothing_to_copy']);
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
        });
    }

    #[Test]
    public function an_existing_blank_row_is_left_unwritten_when_nothing_is_to_be_copied(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => '',
        ]));

        $sequence = $this->sequenceFor([['summary' => '']]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('', $summary->content);
            $this->assertNull($summary->resources);
            $this->assertNull($summary->homework);
            $this->assertNull($summary->private_notes);
            // Nada foi escrito, por isso nunca «Preparada» sem sumário.
            $this->assertSame(LessonStatus::Preparation, $lesson->refresh()->status);
            $this->assertDatabaseCount('lesson_summaries', 1);
        });
    }

    #[Test]
    public function private_notes_is_never_copied_unless_explicitly_requested(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([
            ['summary' => 'Novo conteúdo.', 'private_notes' => 'Nota do item — nunca deve ser copiada.'],
        ]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass, ['private_notes' => false]))->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->summary()->sole();
            $this->assertSame('Novo conteúdo.', $summary->content);
            $this->assertNull($summary->private_notes);
        });
    }

    /**
     * Mudou de propósito: antes aplicava-se aos itens que cabiam e contava os
     * outros como «sem aula». Agora um plano incompleto recusa-se inteiro.
     */
    #[Test]
    public function fewer_eligible_lessons_than_items_refuses_the_whole_application(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        // Origem manual: a materialização que o plano faz para procurar mais
        // horário reconcilia (e remove) as aulas vazias órfãs do horário.
        $lesson = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->inTenant($this->organization, fn () => $lesson->forceFill(['origin' => 'manual'])->save());
        $sequence = $this->sequenceFor([
            ['summary' => 'Item 1.'],
            ['summary' => 'Item 2.'],
            ['summary' => 'Item 3.'],
        ]);

        $preview = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->applyPayload($schoolClass))
            ->assertOk()
            ->assertJsonPath('complete', false)
            ->assertJsonCount(2, 'unplaced');

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass) + ['plan_token' => $preview->json('plan_token')])
            ->assertSessionHasErrors('from');

        $this->assertDatabaseCount('lesson_summaries', 0);
        $this->assertDatabaseMissing('audit_events', ['event' => 'lesson_sequence.applied']);
    }

    #[Test]
    public function zero_eligible_lessons_refuses_without_writing_anything(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->lessonFor($schoolClass, '2026-09-01 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item único.']]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))->assertSessionHasErrors('from');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function apply_reports_the_real_counts_in_the_flash_message(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $prepared = $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $this->lessonFor($schoolClass, '2026-10-07 09:00:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $prepared->id, 'content' => 'Do professor.']));
        $sequence = $this->sequenceFor([['summary' => 'Item 1.']]);

        $response = $this->applyWithPreview($sequence, $this->applyPayload($schoolClass));
        $response->assertRedirect();

        $response->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast'] ?? null) === [
            'type' => 'success',
            'message' => 'Sequência aplicada a partir de 01/10: 1 aula preparada, 1 aula preservada.',
        ]);
    }

    #[Test]
    public function the_date_must_be_today_or_later_and_at_least_one_field_must_be_selected(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->applyPayload($schoolClass, ['from' => '2026-09-30']))
            ->assertStatus(422)->assertJsonValidationErrors('from');

        $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->applyPayload($schoolClass, [
            'summary' => false, 'resources' => false, 'homework' => false, 'private_notes' => false,
        ]))->assertStatus(422)->assertJsonValidationErrors('summary');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function applying_without_a_plan_token_is_refused(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass))
            ->assertSessionHasErrors('plan_token');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_class_of_a_different_subject_is_rejected_before_touching_any_lesson(): void
    {
        $otherSubject = $this->subjectFor();
        $schoolClass = $this->schoolClassFor($this->teacher, $otherSubject);
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasErrors('class_id');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_class_of_a_different_grade_level_is_rejected_when_the_sequence_declares_one(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, gradeLevel: '8.º');
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']], gradeLevel: '7.º');

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasErrors('class_id');

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function a_sequence_with_no_grade_level_matches_a_class_of_any_grade_level(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, gradeLevel: 'Licenciatura');
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item universal.']], gradeLevel: null);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lesson_summaries', 1);
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_target_class_is_forbidden(): void
    {
        $schoolClass = $this->inTenant($this->organization, function (): SchoolClass {
            // No teachers()->attach() — the sequence owner does not teach it.
            return SchoolClass::factory()->recycle($this->organization)->create([
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'grade_level' => '7.º',
            ]);
        });
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->applyWithPreview($sequence, $this->applyPayload($schoolClass))
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    #[Test]
    public function impersonation_blocks_applying_a_sequence(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->lessonFor($schoolClass, '2026-10-06 09:00:00');
        $sequence = $this->sequenceFor([['summary' => 'Item.']]);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->post("/lessons/sequences/{$sequence->ulid}/apply", $this->applyPayload($schoolClass) + ['plan_token' => str_repeat('0', 64)])
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_summaries', 0);
    }

    /**
     * O caminho real do ecrã: pré-visualiza (JSON) e confirma com o plan_token.
     * Quando a pré-visualização recusa, a confirmação segue com um token vazio
     * para que seja a própria recusa do servidor a falar.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyWithPreview(LessonSequence $sequence, array $payload): TestResponse
    {
        $preview = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $payload);
        $token = $preview->status() === 200 ? $preview->json('plan_token') : str_repeat('0', 64);

        return $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $payload + ['plan_token' => $token]);
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id]);
    }

    private function subjectFor(): Subject
    {
        return $this->inTenant($this->organization, fn () => Subject::factory()->recycle($this->organization)->create());
    }

    private function academicYearFor(): AcademicYear
    {
        return $this->inTenant($this->organization, fn () => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));
    }

    private function schoolClassFor(User $teacher, ?Subject $subject = null, ?string $gradeLevel = '7.º'): SchoolClass
    {
        $subject ??= $this->subject;

        return $this->inTenant($this->organization, function () use ($teacher, $subject, $gradeLevel): SchoolClass {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create([
                'subject_id' => $subject->id,
                'academic_year_id' => $this->year->id,
                'grade_level' => $gradeLevel,
            ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    private function lessonFor(SchoolClass $schoolClass, string $startsAt, LessonStatus $status = LessonStatus::Preparation): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            'starts_at' => $startsAt,
            'ends_at' => null,
            // Manual: sem tempo do horário, a materialização que o plano faz não as reconcilia.
            'origin' => 'manual',
            'status' => $status,
            'created_by' => $this->teacher->id,
        ]));
    }

    /**
     * @param  list<array{summary: string, private_notes?: ?string, resources?: ?string, homework?: ?string}>  $items
     */
    private function sequenceFor(array $items, ?string $gradeLevel = null): LessonSequence
    {
        return $this->inTenant($this->organization, function () use ($items, $gradeLevel): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $this->teacher->id,
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'grade_level' => $gradeLevel,
                'name' => 'Sequência de teste',
            ]);

            foreach ($items as $index => $item) {
                $sequence->items()->create([
                    'position' => $index + 1,
                    'summary' => $item['summary'],
                    'private_notes' => $item['private_notes'] ?? null,
                    'resources' => $item['resources'] ?? null,
                    'homework' => $item['homework'] ?? null,
                ]);
            }

            return $sequence;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applyPayload(SchoolClass $schoolClass, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $schoolClass->id,
            'from' => '2026-10-01',
            'summary' => true,
            'resources' => true,
            'homework' => true,
            'private_notes' => true,
        ], $overrides);
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
